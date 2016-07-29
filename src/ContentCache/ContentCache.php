<?php

namespace InfusionWeb\Laravel\ContentCache;

use Guzzle;
use Cache;

class ContentCache
{

    protected $profiles = [];

    protected $current_profile = '';

    public function profile($name)
    {
        $name = strtolower($name);

        if (!array_key_exists($name, $this->profiles)) {
            $this->profiles[$name] = new Profile($name);
        }

        $this->current_profile = $name;

        return $this;
    }

    protected function getProfile($name = '')
    {
        if (!$name) {
            $name = $this->current_profile;
        }

        if (array_key_exists($name, $this->profiles)) {
            return $this->profiles[$name];
        }
    }

    protected function getContent($name = '')
    {
        $profile = $this->getProfile($name);

        $response = Guzzle::get( $profile->getEndpoint(), ['query' => $profile->getQuery()] );

        $collection = collect(json_decode($response->getBody()))
            ->keyBy('id')
            ->transform(function ($item, $key) {
                return new Item($item);
            });

        // Run profile filters on results.
        $profile->filter($collection);

        // Create derivitive fields on result objects.
        $profile->field($collection);

        // Create image derivatives on result objects.
        $profile->createImageDerivatives($collection);

        return $collection;
    }

    public function cache($name = '')
    {
        if (!$name) {
            $name = $this->current_profile;
        }

        $collection = $this->getContent($name);

        $minutes = config("contentcache.{$name}.minutes", config('contentcache.default.minutes', 60));

        Cache::put("{$name}:count", count($collection->all()), $minutes);
        Cache::put("{$name}:all", $collection->all(), $minutes);

        $keys = collect(config("contentcache.{$name}.keys", ['id']));

        // Cache each content item, keyed by each key listed in configuration.
        foreach ($collection->all() as $item) {
            // Check each key listed...
            foreach ($keys->all() as $key) {
                // If the item has an attribute with the same name as the key,
                // cache it, indexed by that key.
                if ($item->hasAttribute($key)) {
                    Cache::put("{$name}:{$key}:{$item->$key}", $item, $minutes);
                }
            }
        }

        return $this;
    }

    public function getAll($name = '')
    {
        if (!$name) {
            $name = $this->current_profile;
        }

        $items = (array) Cache::get("{$name}:all");

        return $items;
    }

    public function getBy($key, $value, $name = '')
    {
        if (!$name) {
            $name = $this->current_profile;
        }

        if (is_array($value)) {
            $items = [];

            foreach ($value as $val) {
                $items[$v] = Cache::get("{$name}:{$key}:{$val}");
            }

            return $items;
        }

        return Cache::get("{$name}:{$key}:{$value}");
    }

    public function __call($name, $arguments)
    {
        if (substr($name, 0, 5) == 'getBy') {
            $key = lcfirst(substr($name, 5));

            // Getting a little tricky here, but it works.
            list($value, $name) = array_merge($arguments, ['']);

            return $this->getBy($key, $value, $name);
        }

        throw new \BadMethodCallException("The method '$name' does not exist");
    }

}
