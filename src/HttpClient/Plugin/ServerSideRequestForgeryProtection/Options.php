<?php

namespace Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection;

use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\Exception\InvalidOptionException;

class Options
{
    /**
     * @var bool Allow credentials in a URL
     */
    private $sendCredentials = false;

    /**
     * @var bool Pin DNS records
     */
    private $pinDns = false;

    /**
     * @var array
     */
    private $whitelist = [
        'ip' => [],
        'port' => ['80', '443', '8080'],
        'domain' => [],
        'scheme' => ['http', 'https'],
    ];

    /**
     * @var array
     */
    private $blacklist = [
        'ip' => [
            '0.0.0.0/8',
            '10.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.0.0/29',
            '192.0.2.0/24',
            '192.88.99.0/24',
            '192.168.0.0/16',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4',
        ],
        'port' => [],
        'domain' => [],
        'scheme' => [],
    ];

    /**
     * Get send credentials option.
     *
     * @return bool
     */
    public function getSendCredentials()
    {
        return $this->sendCredentials;
    }

    /**
     * Enable sending of credenitals
     * This is potentially a security risk.
     *
     * @return Options
     */
    public function enableSendCredentials()
    {
        $this->sendCredentials = true;

        return $this;
    }

    /**
     * Disable sending of credentials.
     *
     * @return Options
     */
    public function disableSendCredentials()
    {
        $this->sendCredentials = false;

        return $this;
    }

    /**
     * Get pin DNS option.
     *
     * @return bool
     */
    public function getPinDns()
    {
        return $this->pinDns;
    }

    /**
     * Enable DNS pinning.
     *
     * @return Options
     */
    public function enablePinDns()
    {
        $this->pinDns = true;

        return $this;
    }

    /**
     * Disable DNS pinning.
     *
     * @return Options
     */
    public function disablePinDns()
    {
        $this->pinDns = false;

        return $this;
    }

    /**
     * Checks if a specific value is in a list.
     *
     * @param $list   string
     * @param $type   string
     * @param $value
     *
     * @throws InvalidOptionException
     *
     * @return bool
     */
    public function isInList($list, $type, $value)
    {
        if (!in_array($list, ['whitelist', 'blacklist'], true)) {
            throw new InvalidOptionException('Provided list "' . $list . '" must be "whitelist" or "blacklist"');
        }

        if (!array_key_exists($type, $this->$list)) {
            throw new InvalidOptionException('Provided type "' . $type . '" must be "ip", "port", "domain" or "scheme"');
        }

        if (empty($this->{$list}[$type])) {
            return 'whitelist' === $list;
        }

        //For domains, a regex match is needed
        if ('domain' === $type) {
            foreach ($this->{$list}[$type] as $domain) {
                if (preg_match('/^' . $domain . '$/i', $value)) {
                    return true;
                }
            }

            return false;
        }

        return in_array($value, $this->{$list}[$type], true);
    }

    /**
     * Returns a specific list.
     *
     * @param $list string
     * @param $type string optional
     *
     * @throws InvalidOptionException
     *
     * @return array
     */
    public function getList($list, $type = null)
    {
        if (!in_array($list, ['whitelist', 'blacklist'], true)) {
            throw new InvalidOptionException('Provided list "' . $list . '" must be "whitelist" or "blacklist"');
        }

        if (null !== $type) {
            if (!array_key_exists($type, $this->$list)) {
                throw new InvalidOptionException('Provided type "' . $type . '" must be "ip", "port", "domain" or "scheme"');
            }

            return $this->{$list}[$type];
        }

        return $this->{$list};
    }

    /**
     * Sets a list to the passed in array.
     *
     * @param $list   string
     * @param $values array
     * @param $type   string optional
     *
     * @throws InvalidOptionException
     *
     * @return Options
     */
    public function setList($list, $values, $type = null)
    {
        if (!in_array($list, ['whitelist', 'blacklist'], true)) {
            throw new InvalidOptionException('Provided list "' . $list . '" must be "whitelist" or "blacklist"');
        }

        if (!is_array($values)) {
            throw new InvalidOptionException('Provided values must be an array, "' . gettype($values) . '" given');
        }

        if (null !== $type) {
            if (!array_key_exists($type, $this->$list)) {
                throw new InvalidOptionException('Provided type "' . $type . '" must be "ip", "port", "domain" or "scheme"');
            }

            $this->{$list}[$type] = $values;

            return $this;
        }

        foreach ($values as $type => $value) {
            if (!in_array($type, ['ip', 'port', 'domain', 'scheme'], true)) {
                throw new InvalidOptionException('Provided type "' . $type . '" must be "ip", "port", "domain" or "scheme"');
            }

            $this->{$list}[$type] = $value;
        }

        return $this;
    }

    /**
     * Adds a value/values to a specific list.
     *
     * @param $list   string
     * @param $type   string
     * @param $values array|string
     *
     * @throws InvalidOptionException
     *
     * @return Options
     */
    public function addToList($list, $type, $values)
    {
        if (!in_array($list, ['whitelist', 'blacklist'], true)) {
            throw new InvalidOptionException('Provided list "' . $list . '" must be "whitelist" or "blacklist"');
        }

        if (!array_key_exists($type, $this->$list)) {
            throw new InvalidOptionException('Provided type "' . $type . '" must be "ip", "port", "domain" or "scheme"');
        }

        if (empty($values)) {
            throw new InvalidOptionException('Provided values cannot be empty');
        }

        //Cast single values to an array
        if (!is_array($values)) {
            $values = [$values];
        }

        foreach ($values as $value) {
            if (!in_array($value, $this->{$list}[$type], true)) {
                $this->{$list}[$type][] = $value;
            }
        }

        return $this;
    }

    /**
     * Removes a value/values from a specific list.
     *
     * @param $list   string
     * @param $type   string
     * @param $values array|string
     *
     * @throws InvalidOptionException
     *
     * @return Options
     */
    public function removeFromList($list, $type, $values)
    {
        if (!in_array($list, ['whitelist', 'blacklist'], true)) {
            throw new InvalidOptionException('Provided list "' . $list . '" must be "whitelist" or "blacklist"');
        }

        if (!array_key_exists($type, $this->$list)) {
            throw new InvalidOptionException('Provided type "' . $type . '" must be "ip", "port", "domain" or "scheme"');
        }

        if (empty($values)) {
            throw new InvalidOptionException('Provided values cannot be empty');
        }

        //Cast single values to an array
        if (!is_array($values)) {
            $values = [$values];
        }

        $this->{$list}[$type] = array_diff($this->{$list}[$type], $values);

        return $this;
    }
}
