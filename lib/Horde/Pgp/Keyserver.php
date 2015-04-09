<?php
/**
 * Copyright 2002-2015 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2002-2015 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Pgp
 */

/**
 * Provides methods to connect to a PGP keyserver.
 *
 * Connects to a public key server via HKP (Horrowitz Keyserver Protocol).
 * http://tools.ietf.org/html/draft-shaw-openpgp-hkp-00
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2002-2015 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Pgp
 */
class Horde_Pgp_Keyserver
{
    /** Default keyserver. */
    const DEFAULT_KEYSERVER = 'pool.sks-keyservers.net';

    /**
     * HTTP object.
     *
     * @var Horde_Http_Client
     */
    protected $_http;

    /**
     * Keyserver hostname.
     *
     * @var string
     */
    protected $_keyserver;

    /**
     * Constructor.
     *
     * @param array $params  Optional parameters:
     * <pre>
     *   - http: (Horde_Http_Client) The HTTP client object to use.
     *   - keyserver: (string) The public PGP keyserver to use.
     *   - port: (integer) The public PGP keyserver port.
     * </pre>
     */
    public function __construct(array $params = array())
    {
        $this->_http = (isset($params['http']) && ($params['http'] instanceof Horde_Http_Client))
            ? $params['http']
            : new Horde_Http_Client();
        $this->_keyserver = isset($params['keyserver'])
            ? $params['keyserver']
            : self::DEFAULT_KEYSERVER;
        $this->_keyserver .= ':' . (isset($params['port']) ? $params['port'] : '11371');
    }

    /**
     * Returns PGP public key data retrieved from a public keyserver.
     *
     * @param string $id  The ID of the PGP key.
     *
     * @return Horde_Pgp_Element_PublicKey  The PGP public key.
     * @throws Horde_Pgp_Exception
     */
    public function get($id)
    {
        /* Connect to the public keyserver. */
        $url = $this->_createUrl('/pks/lookup', array(
            'op' => 'get',
            'options' => 'mr',
            'search' => $this->_getKeyIdString($id)
        ));

        try {
            $output = $this->_http->get($url)->getBody();
        } catch (Horde_Http_Exception $e) {
            throw new Horde_Pgp_Exception($e);
        }

        /* Grab PGP key from output. */
        try {
            return Horde_Pgp_Element_PublicKey::create($output);
        } catch (Exception $e) {
            throw new Horde_Pgp_Exception(Horde_Pgp_Translation::t(
                "Could not obtain public key from the keyserver."
            ));
        }
    }

    /**
     * Sends a PGP public key to a public keyserver.
     *
     * @param string $pubkey  The PGP public key.
     *
     * @throws Horde_Pgp_Exception
     */
    public function put($pubkey)
    {
        /* Get the key ID of the public key. */
        $key = Horde_Pgp_Element_PublicKey::create($pubkey);

        /* See if the public key already exists on the keyserver. */
        try {
            $this->get($key->id);
        } catch (Horde_Pgp_Exception $e) {
            $pubkey = 'keytext=' . urlencode(rtrim($key->getPublicKey()));
            try {
                $this->_http->post(
                    $this->_createUrl('/pks/add'),
                    $pubkey,
                    array(
                        'User-Agent: Horde Application Framework',
                        'Content-Type: application/x-www-form-urlencoded',
                        'Content-Length: ' . strlen($pubkey),
                        'Connection: close'
                    )
                );
            } catch (Horde_Http_Exception $e) {
                throw new Horde_Pgp_Exception($e);
            }
        }

        throw new Horde_Pgp_Exception(Horde_Pgp_Translation::t(
            "Key already exists on the public keyserver."
        ));
    }

    /**
     * Returns the first matching key for an email address from a public
     * keyserver.
     *
     * @param string $address  The email address to search for.
     *
     * @return string  The PGP key ID.
     * @throws Horde_Pgp_Exception
     */
    public function getKeyByEmail($address)
    {
        $pubkey = null;

        /* Connect to the public keyserver. */
        $url = $this->_createUrl('/pks/lookup', array(
            'op' => 'index',
            'options' => 'mr',
            'search' => $address
        ));

        try {
            $output = $this->_http->get($url)->getBody();
        } catch (Horde_Http_Exception $e) {
            throw new Horde_Pgp_Exception($e);
        }

        if (strpos($output, '-----BEGIN PGP PUBLIC KEY BLOCK') !== false) {
            return Horde_Pgp_Element_PublicKey::create($output);
        } elseif (strpos($output, 'pub:') !== false) {
            $output = explode("\n", $output);
            $keyids = $keyuids = array();
            $curid = null;

            foreach ($output as $line) {
                if (substr($line, 0, 4) == 'pub:') {
                    $line = explode(':', $line);
                    /* Ignore invalid lines and expired keys. */
                    if (count($line) != 7 ||
                        (!empty($line[5]) && $line[5] <= time())) {
                        continue;
                    }
                    $curid = $line[4];
                    $keyids[$curid] = $line[1];
                } elseif (!is_null($curid) && substr($line, 0, 4) == 'uid:') {
                    preg_match("/<([^>]+)>/", $line, $matches);
                    $keyuids[$curid][] = $matches[1];
                }
            }

            /* Remove keys without a matching UID. */
            foreach ($keyuids as $id => $uids) {
                $match = false;
                foreach ($uids as $uid) {
                    if ($uid == $address) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    unset($keyids[$id]);
                }
            }

            /* Sort by timestamp to use the newest key. */
            if (count($keyids)) {
                ksort($keyids);
                return $this->get(array_pop($keyids));
            }
        }

        throw new Horde_Pgp_Exception(
            Horde_Pgp_Translation::t("Could not obtain public key from the keyserver.")
        );
    }

    /**
     * Create the URL for the keyserver.
     *
     * @param string $uri    Action URI.
     * @param array $params  List of parameters to add to URL.
     *
     * @return Horde_Url  Keyserver URL.
     */
    protected function _createUrl($uri, array $params = array())
    {
        $url = new Horde_Url($this->_keyserver . $uri, true);
        return $url->add($params);
    }

    /**
     */
    protected function _getKeyIdString($keyid)
    {
        /* Get the 8 character key ID string. */
        if (strpos($keyid, '0x') === 0) {
            $keyid = substr($keyid, 2);
        }
        if (strlen($keyid) > 8) {
            $keyid = substr($keyid, -8);
        }
        return '0x' . $keyid;
    }

}
