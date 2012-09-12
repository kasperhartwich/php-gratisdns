<?php
/**
 * ProjectName: php-gratisdns
 * Plugin URI: http://github.com/kasperhartwich/php-gratisdns
 * Description: Altering your DNS records at GratisDNS
 *
 * @author Kasper Hartwich <kasper@hartwich.net>
 * @package php-gratisdns
 * @version 0.9.3
 */

class GratisDNS {
  private $username;
  private $password;
  public $admin_url = 'https://oldsystem.gratisdns.dk/editdomains4.phtml';
  public $curl = null;
  public $domain = null;
  public $domains = null;
  public $records = null;
  public $response = null;
  public $html = null;

  function __construct($username, $password, $verify_certificate = true) {
    require_once __DIR__.'/simple_html_dom.php';
    $this->username = $username;
    $this->password = $password;

    if (!function_exists('curl_init')) {
      throw new Exception('php cURL extension must be installed and enabled');
    }

    $this->curl = curl_init();
    // Having certificates problems: http://curl.haxx.se/docs/sslcerts.html
    curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, $verify_certificate);   
    curl_setopt($this->curl, CURLOPT_URL, $this->admin_url);
    curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->curl, CURLOPT_POST, true);
  }

  function getDomains($section = 'primary') {
    if ($section == 'primary') {
      $html = $this->_request(array('action' => 'primarydns'));
    } else {
      $html = $this->_request(array('action' => 'secondarydns'));
    }

    $htmldom = new simple_html_dom();
    $htmldom->load($html);

    $this->domains = array();
    foreach($htmldom->find('form[name=domainform] input[name=domain]') as $input) {
      $this->domains[] = utf8_encode($input->value);
    }
    return $this->domains;
  }

  /**
   * Retrieve domain ID
   *
   * @param string $domain
   * @return boolean
   */
  public function getDomainId($domain) {
    $html = $this->_request(array('action' => 'changeDNSsetup', 'user_domain' => $domain));
    $htmldom = new simple_html_dom();
    $htmldom->load($html);

    if (!$this->_response($html)) {
      return false;
    }

    foreach($htmldom->find('input[name=domainid]') as $input) {
      $value = (int)$input->value; // Make suer we get a int so we can use empty() to check value with.
      if (!empty($value)) {
        return $value;
      }
    }

    return false;
  }

  function getRecordByDomain($domain, $type, $host) {
    $domaininfo = $this->getRecords($domain);
    if ($domaininfo) {
      if (isset($domaininfo[$type][$host])) {
        return $domaininfo[$type][$host];
      } else {
        return $this->error("Unknown host '" . $host . "' for recordtype '" . $type . "'");
      }
    } else {
      return false;
    }
  }

  function getRecordById($domain, $id) {
    if (!$this->getRecords($domain)) {
      return false;
    };

    $record = $this->lookupRecord($id);
    if ($record) {
      return $record;
    }
    return $this->error("Unknown record_id '" . $id . "' for domain '" . $domain . "'");
  }

  function getRecords($domain) {
    $html = $this->_request(array('action' => 'changeDNSsetup', 'user_domain' => $domain));
    $htmldom = new simple_html_dom();
    $htmldom->load($html);

    if ($this->_response($html)) {
      $this->records[$domain] = array();
      foreach($htmldom->find('tr[class=BODY1BG],tr[class=BODY2BG]') as $tr) {
        $type = $tr->parent()->find('td[class=TITLBG] b', 0)->innertext;
        if (in_array($type, array('A', 'AAAA', 'MX', 'AFSDB', 'TXT', 'NS', 'SRV', 'SSHFP'))) {
          if (!isset($this->records[$domain][$type])) {$this->records[$domain][$type] = array();}
          $tds = $tr->find('td');
          $recordid = $tr->find('td form input[name=recordid]', 0) ? (int)$tr->find('td form input[name=recordid]',0)->value : 0;
          $host = (in_array($type, array('NS', 'SRV', 'TXT', 'MX'))) ? count($this->records[$domain][$type]) : utf8_encode($tds[0]->innertext);
          $this->records[$domain][$type][$host]['type'] = $type;
          $this->records[$domain][$type][$host]['recordid'] = $recordid;
          $this->records[$domain][$type][$host]['domainid'] = $tr->find('td form input[name=domainid]', 0) ? (int)$tr->find('td form input[name=domainid]', 0)->value : 0;
          $this->records[$domain][$type][$host]['host'] = utf8_encode($tds[0]->innertext);
          $this->records[$domain][$type][$host]['data'] = utf8_encode($tds[1]->innertext);
          switch ($type) {
            case 'A':
            case 'AAAA':
            case 'CNAME':
              $this->records[$domain][$type][$host]['ttl'] = (int)$tds[2]->innertext;
              break;
            case 'MX':
            case 'AFSDB':
              if (!$recordid) {
                unset($this->records[$domain][$type][$host]);
              } else {
                $this->records[$domain][$type][$host]['preference'] = $tds[2]->innertext;
                $this->records[$domain][$type][$host]['ttl'] = (int)$tds[3]->innertext;
              }
              break;
            case 'TXT':
            case 'NS':
              //No extra options
              break;
            case 'SRV':
              $this->records[$domain][$type][$host]['priority'] = $tds[2]->innertext;
              $this->records[$domain][$type][$host]['weight'] = (int)$tds[3]->innertext;
              $this->records[$domain][$type][$host]['port'] = (int)$tds[4]->innertext;
              $this->records[$domain][$type][$host]['ttl'] = (int)$tds[5]->innertext;
              break;
            case 'SSHFP':
              //Not supported
              break;
          }
        }
      }
      return $this->records[$domain];
    } else {
      return false;
    }
  }

  function createDomain($domain, $type = 'primary', $primary_ns = false, $secondary_ns = false) {
    if ($type == 'primary') {
      $post_array = array('action' => 'createprimaryandsecondarydnsforthisdomain', 'user_domain' => $domain);
    } else {
      $post_array = array('action' => 'createsecondarydnsforthisdomain', 'user_domain' => $domain, 'user_domain_ip' => $primary_ns);
      $post_array['user_domain_ip2'] = ($secondary_ns) ? $secondary_ns : 'xxx.xxx.xxx.xxx';
    }
    $html = $this->_request($post_array);
    return $this->_response($html);
  }

  function deleteDomain($domain) {
    $html = $this->_request(array('action' => 'deleteprimarydnsnow', 'user_domain' => $domain));
    return $this->_response($html);
  }

  /**
   *
   * @param string $domain
   * @param string $type
   * @param string $host
   * @param string $data
   * @param integer $ttl Use with caution. Some guess work is involved and we may end up setting TTL on the wrong record
   * @param string $preference
   * @param integer $weight
   * @param integer $port
   * @return type
   */
  function createRecord($domain, $type, $host, $data, $ttl = false, $preference = false, $weight = false, $port = false) {
    $post_array = array(
      'action' => 'add' . strtolower($type). 'record',
      'user_domain' => $domain,
    );
    switch ($type) {
      case 'A':
      case 'AAAA':
        $post_array['host'] = $host;
        $post_array['ip'] = $data;
        break;
      case 'CNAME':
        $post_array['host'] = $host;
        $post_array['kname'] = $data;
        break;
      case 'MX':
      case 'AFSDB':
        $post_array['host'] = $host;
        $post_array['exchanger'] = $data;
        $post_array['preference'] = $preference;
        break;
      case 'TXT':
      case 'NS':
        $post_array['leftRR'] = $host;
        $post_array['rightRR'] = $data;
        break;
      case 'SRV':
        $post_array['host'] = $host;
        $post_array['exchanger'] = $data;
        $post_array['preference'] = $preference;
        $post_array['weight'] = $weight;
        $post_array['port'] = $port;
        break;
      case 'SSHFP':
        $post_array['host'] = $host;
        $post_array['rightRR'] = $data;
        $post_array['preference'] = $preference;
        $post_array['weight'] = $weight;
        break;
    }
    $html = $this->_request($post_array);
    $response = $this->_response($html);
    if ($response && $ttl) {
      // Here be Dragons, recommend not to use this feature.
      $record = $this->getRecordByDomain($domain, $type, $host);
      $html = $this->updateRecord($domain, $record['recordid'], $type, $host, $data, $ttl);
      return $this->_response($html);
    } else {
      return $this->_response($html);
    }
  }


  /**
   * @param string $domain
   * @param integer $recordid
   * @param string $type
   * @param string $host
   * @param type $data
   * @param type $ttl
   * @return boolean
   */
  public function updateRecord($domain, $recordid, $type = false, $host = false, $data = false, $ttl = false) {
    $post_array = array(
      'action' => 'makechangesnow',
      'user_domain' => $domain,
      'recordid' => $recordid,
    );

    if ($type) {
      $post_array['type'] = $type;
    } else {
      $record = $this->getRecordById($domain, $recordid);
      if (!$record) {
        return false;
      }
      $post_array['type'] = $record['type'];
      $type = $record['type'];
    }
    if ($host) {
      $post_array['host'] = $host;
    } else {
      $record = $this->getRecordById($domain, $recordid);
      if (!$record) {
        return false;
      }
      $post_array['host'] = $record['host'];
    }
    switch ($type) {
      case 'A':
      case 'AAAA':
      case 'MX':
      case 'CNAME':
      case 'TXT':
      case 'AFSDB':
        if (!$ttl) {
          $record = $this->getRecordById($domain, $recordid);
          if (!$record) {
            return false;
          }
          $ttl = $record['ttl'];
        }
        $post_array['new_data'] = $data;
        $post_array['new_ttl'] = $ttl;
        break;
      case 'SRV':
        $post_array['new_ttl'] = $ttl;
        break;
      case 'NS':
        return $this->error('Updating NS record is not supported by GratisDNS.');
      case 'SSHFP':
        return $this->error('Not supported.');
    }
    $html = $this->_request($post_array);
    return $this->_response($html);
  }

  function applyTemplate($domain, $template, $ttl = false) {
    switch ($template) {
      //Feel free to fork and add other templates. :)
      case 'googleapps':
        $this->createRecord($domain, 'MX', $domain, 'aspmx.l.google.com', $ttl, 1);
        $this->createRecord($domain, 'MX', $domain, 'alt1.aspmx.l.google.com', $ttl, 5);
        $this->createRecord($domain, 'MX', $domain, 'alt2.aspmx.l.google.com', $ttl, 5);
        $this->createRecord($domain, 'MX', $domain, 'aspmx2.googlemail.com', $ttl, 10);
        $this->createRecord($domain, 'MX', $domain, 'aspmx3.googlemail.com', $ttl, 10);
        $this->createRecord($domain, 'CNAME', 'mail.' . $domain, 'ghs.google.com', $ttl);
        $this->createRecord($domain, 'CNAME', 'start.' . $domain, 'ghs.google.com', $ttl);
        $this->createRecord($domain, 'CNAME', 'calendar.' . $domain, 'ghs.google.com', $ttl);
        $this->createRecord($domain, 'CNAME', 'docs.' . $domain, 'ghs.google.com', $ttl);
        $this->createRecord($domain, 'CNAME', 'sites.' . $domain, 'ghs.google.com', $ttl);
        $this->createRecord($domain, 'SRV', '_jabber._tcp.' . $domain, 'xmpp-server.l.google.com', $ttl, 5, 0, 5269);
        $this->createRecord($domain, 'SRV', '_jabber._tcp.' . $domain, 'xmpp-server1.l.google.com', $ttl, 20, 0, 5269);
        $this->createRecord($domain, 'SRV', '_jabber._tcp.' . $domain, 'xmpp-server2.l.google.com', $ttl, 20, 0, 5269);
        $this->createRecord($domain, 'SRV', '_jabber._tcp.' . $domain, 'xmpp-server3.l.google.com', $ttl, 20, 0, 5269);
        $this->createRecord($domain, 'SRV', '_jabber._tcp.' . $domain, 'xmpp-server4.l.google.com', $ttl, 20, 0, 5269);
        $this->createRecord($domain, 'SRV', '_xmpp-server._tcp.' . $domain, 'xmpp-server.l.google.com', $ttl, 5, 0, 5269);
        $this->createRecord($domain, 'SRV', '_xmpp-server._tcp.' . $domain, 'xmpp-server1.l.google.com', $ttl, 20, 0, 5269);
        $this->createRecord($domain, 'SRV', '_xmpp-server._tcp.' . $domain, 'xmpp-server2.l.google.com', $ttl, 20, 0, 5269);
        $this->createRecord($domain, 'SRV', '_xmpp-server._tcp.' . $domain, 'xmpp-server3.l.google.com', $ttl, 20, 0, 5269);
        $this->createRecord($domain, 'SRV', '_xmpp-server._tcp.' . $domain, 'xmpp-server4.l.google.com', $ttl, 20, 0, 5269);
        break;
      default:
        return error('Unknown template');
    }
  }

  /**
   * Delete a record
   *
   * @param string|integer $domain Domain name or domainid
   * @param integer $recordid Record to be deleted
   * @param string $type Record type, this is required if a domainid has been specified
   * @return
   */
  function deleteRecord($domain, $recordid, $type = null) {

    $have_domain = false;
    if (is_numeric($domain)) {
      $domainid = $domain;
    } else {
      $domainid = $this->getDomainId($domain);
      if (!$domainid) {
        return false;
      }
      $have_domain = true;
    }

    if (!$type) {
      if (!$have_domain) {
        throw new \Exception('We dont support using domainid without specifying $type');
      }

      $record = $this->getRecordById($domain, $recordid);
      if (!$record) {
        return false;
      }

      $type = $record['type'];
    }

    $params = array(
      'action' => 'deletegeneric',
      'domainid' => $domainid,
      'recordid' => $recordid,
      'typeRR' => $type
    );

    $html = $this->_request($params);
    return $this->_response($html);
  }

  function getResponse() {
    return strip_tags(str_replace($this->domain, '', $this->response));
  }

  private function _request($args = array()) {
    if (isset($args['user_domain'])) {$this->domain = $args['user_domain'];}
    $post_array = array_merge(array('user' => $this->username, 'password' => $this->password), $args);
    curl_setopt($this->curl, CURLOPT_POSTFIELDS, $post_array);
    $this->html = curl_exec($this->curl);
    return $this->html;
  }

  private function _response($html) {
    $htmldom = new simple_html_dom();
    $htmldom->load($html);
    $this->response = trim(utf8_encode($htmldom->find('td[class=systembesked]',0)->innertext));
    $positive_messages = array('successfyldt', 'er oprettet', 'er slettet', $this->domain);
    foreach ($positive_messages as $positive_message) {
      if ( strstr($this->response, $positive_message) ) {
        return true;
      }
    }
    return false;
  }

  private function error($response) {
    $this->response = $response;
    return false;
  }

  private function lookupRecord($recordid) {

    if (0 == $recordid) {
      // We dont want to match NS records
      throw new \Exception('Record may not be 0');
    }

    foreach ($this->records as $host) {
      foreach ($host as $type) {
        foreach ($type as $record) {
          if ($recordid == $record['recordid']) {
            return $record;
          }
        }
      }
    }
    return false;
  }
}

