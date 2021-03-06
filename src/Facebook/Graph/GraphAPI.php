<?php
/**
 * MIT license
 */
namespace Facebook\Graph;

use Facebook\Graph\Event;
use Facebook\Graph\Post;
use Facebook\Graph\User;

/**
 * @author Richard Shank <develop@zestic.com>
 */
class GraphAPI
{
    private $facebook;
    private $lexer;
    private $parser;
    private $reader;

    public function __construct($facebook)
    {
        /** @var \Facebook facebook */
        $this->facebook = $facebook;
        $this->lexer =  new \Doctrine\Common\Annotations\DocLexer();
        $this->parser = new \Doctrine\Common\Annotations\DocParser();
        $this->parser->setImports(array('@return'));

        $this->reader = new \Doctrine\Common\Annotations\AnnotationReader(
            new \Doctrine\Common\Cache\ApcCache(),
            new \Doctrine\Common\Annotations\DocParser()
        );
    }

    /**
     * Fetch the events from an id page
     *
     * @param string $facebookId the id for the page to retrieve the events
     * @param array querying parameters
     *        - limit
     *        - offset
     *        - since (a unix timestamp or any date accepted by strtotime)
     *        - until (a unix timestamp or any date accepted by strtotime)
     *
     * @return array of Facebook\Graph\Event
     */
    public function fetchEvents($facebookId, $parameters = array())
    {
        $api = sprintf('/%s/events', $facebookId);
        return $this->fetchData($api, 'Facebook\\Graph\\Event', $parameters);
    }

    /**
     * Fetch the currently authenticated user. Return null if there isn't an authenticated user
     *
     * @return \Facebook\Graph\User | null
     */
    public function fetchMe()
    {
 /*
        $userId = $this->facebook->getUser();
        if ($userId === 0) {
            return null;
        }

        return $this->fetchUser($userId);
*/
        try {
            $data = $this->facebook->api('/me');
        } catch (\FacebookApiException $e) {
            return null;
        }

        return $this->mapDataToObject($data, new User());
    }

    /**
     * Fetch the posts from an id page
     *
     * @param string $facebookId the id for the page to retrieve the posts
     * @param array querying parameters
     *        - limit defaults to 10
     *        - offset
     *        - since (a unix timestamp or any date accepted by strtotime) defaults to "-1 day"
     *        - until (a unix timestamp or any date accepted by strtotime)
     * @return array of Facebook\Graph\Post
     */
    public function fetchPosts($facebookId, $parameters = array())
    {
        $api = sprintf('/%s/posts', $facebookId);
        return $this->fetchData($api, 'Facebook\\Graph\\Post', $parameters);
    }

    /**
     * Fetch the user from an id
     *
     * @param string $facebookId the id for the page to retrieve the posts
     * @return array of Facebook\Graph\User
     */
    public function fetchUser($facebookId)
    {
        $api = sprintf('/%s', $facebookId);
        try {
            $data = $this->facebook->api($api);
        } catch (\FacebookApiException $e) {
            return array();
        }

        return $this->mapDataToObject($data, new User());
    }

    /**
     * Return an object for a url, even if its just the username or id that is passed in
     *
     * @param $url string
     *
     * @return object based on type
     */
    public function findObjectFromUrl($url)
    {
        $url = strtolower($url);
        $parsed = parse_url($url);
        $id = $parsed['path'];
        if (isset($parsed['fragment'])) {
            $id = $parsed['fragment'];
        }
        if (isset($parsed['query'])) {
            $inThere = strrpos($parsed['query'], '=');
            $id = substr($parsed['query'], $inThere + 1);
        }
        if ($url === $parsed['path'] && $inThere = strpos($url, 'facebook.com')) {
            $id = substr($url, $inThere + 12);
        }
        if (strpos($parsed['path'], 'pages') !== false) {
            $inThere = strrpos($url, '/');
            $id = substr($url, $inThere);
        }
        $id = trim($id, '/');
        try {
            $raw = $this->facebook->api($id);
        } catch (\FacebookApiException $e) {

            return null;
        }

        if (!isset($raw['link'])) {
            $username = isset($raw['username']) ? $raw['username'] : $raw['id'];
            $raw['link'] = 'http://www.facebook.com/' . $username;
        }
        return $raw;

        // todo: actually map this to an object, for now, just passing back the raw data
        // $objectClass = '\\Facebook\\Graph\\' . ucfirst(strtolower($raw['type']));
        // $object = new $objectClass();

        //return $this->mapDataToObject($raw, $object);
    }

    protected function fetchData($api, $objectClass, $parameters)
    {
        $parameters = array_merge(array('limit' => 10, 'since' => '-1 week'), $parameters);
        $api = $api . '?' . http_build_query($parameters);
        try {
            $raw = $this->facebook->api($api);
        } catch (\FacebookApiException $e) {
            return array();
        }

        $objects = array();
        foreach ($raw['data'] as $data) {
            $object = new $objectClass();
            $objects[] = $this->mapDataToObject($data, $object);
        }

        return $objects;
    }

    protected function mapDataToObject($data, &$object)
    {
        $rc = new \ReflectionClass($object);
        foreach ($data as $field => $value) {
            $propertyName = preg_replace('/_(.?)/e', "strtoupper('$1')", $field);
            try {
                $property = $rc->getProperty($propertyName);
            } catch (\ReflectionException $e) {
                continue;
            }
            $property->setAccessible(true);
            $methodName = 'get' . ucfirst($propertyName);
            $method = $rc->getMethod($methodName);
            if ($returnObject = $this->getReturnObject($method)) {
                if ($returnObject == "\\DateTime") {
                    $property->setValue($object, new $returnObject($data[$field]));
                    continue;
                }
                $newObject = new $returnObject;
                $this->mapDataToObject($value, $newObject);
                $property->setValue($object, $newObject);
            } else {
                $property->setValue($object, $value);
            }
        }
        return $object;
    }

    protected function getReturnObject(\ReflectionMethod $method)
    {
        $comment = $method->getDocComment();
        $this->lexer->setInput($comment);
        $object = null;
        while ($this->lexer->moveNext() && !$object) {
            $this->lexer->skipUntil(\Doctrine\Common\Annotations\DocLexer::T_AT);
            $this->lexer->moveNext();
            if ($this->lexer->lookahead['value'] == 'return') {
                $this->lexer->moveNext();
                $object = '\\';
                do {
                    $object = $object . $this->lexer->lookahead['value'];
                    $this->lexer->moveNext();
                } while ($this->lexer->lookahead['value'] != '/');
            }
        }
        return class_exists($object) ? $object : false;
    }
}
