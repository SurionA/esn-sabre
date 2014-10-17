<?php
namespace ESN\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;

class ESNHookPlugin extends ServerPlugin {

    protected $server;

    private $apiroot;
    private $communities_principals;
    private $httpClient;
    private $request;
    private $connect_cookie;

    function __construct($apiroot, $communities_principals) {
      $this->apiroot = $apiroot;
      $this->communities_principals = $communities_principals;
    }

    function initialize(Server $server) {
        $this->server = $server;

        $server->on('method:PUT',         [$this, 'isMethodCatchable'], 90);
        $server->on('method:DELETE',      [$this, 'isMethodCatchable'], 90);

        $server->on('beforeCreateFile',   [$this, 'beforeCreateFile']);
        $server->on('afterCreateFile',    [$this, 'afterCreateFile']);

        $server->on('beforeWriteContent', [$this, 'beforeWriteContent']);
        $server->on('afterWriteContent',  [$this, 'afterWriteContent']);

        $server->on('beforeUnbind',       [$this, 'beforeUnbind']);
        $server->on('afterUnbind',        [$this, 'afterUnbind']);

        $server->on('afterLogin',         [$this, 'afterLogin'], 90);

        $this->httpClient = new HTTP\Client();
    }

    function afterLogin($connect_cookie) {
      $this->connect_cookie = $connect_cookie;
      return true;
    }

    function isMethodCatchable(RequestInterface $request, ResponseInterface $response) {
        $request_uri = $request->getRawServerValue('REQUEST_URI');
        if (strpos($request_uri, '.ics') === false) {
          return false;
        }
        $pathAsArray = explode('/', $request_uri);
        $community_id = $pathAsArray[2];
        $principal_uri = '/'.$this->communities_principals.'/'.$community_id;
        if (!$this->server->tree->nodeExists($principal_uri)) {
            return false;
        }
        return true;
    }

    function beforeUnbind($path) {
        $pathAsArray = explode('/', $path);
        $community_id = $pathAsArray[1];
        $object_uri = array_pop($pathAsArray);

        $node = $this->server->tree->getNodeForPath($path);
        $data = $node->get();

        $bodyAsArray = [ 'event_id' => '/'.$path, 'type' => 'deleted', 'event' => $data ];
        $body = json_encode($bodyAsArray);

        $this->createRequest($community_id, $body);

        return true;
    }

    function afterUnbind($path) {
        $this->sendAsync($this->request);

        return true;
    }

    function beforeCreateFile($path, &$data, \Sabre\DAV\ICollection $parent, &$modified) {
        $community_id = $this->getCommunityIdFrom($parent->getOwner());

        $bodyAsArray = [ 'event_id' => '/'.$path, 'type' => 'created', 'event' => $data ];
        $body = json_encode($bodyAsArray);

        $this->createRequest($community_id, $body);

        return true;
    }

    function afterCreateFile($path, \Sabre\DAV\ICollection $parent) {
        $this->sendAsync($this->request);

        return true;
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        $community_id = $this->getCommunityIdFrom($node->getOwner());
        $old_event = $node->get();

        $bodyAsArray = [ 'event_id' => '/'.$path, 'type' => 'updated', 'event' => $data, 'old_event' => $old_event ];
        $body = json_encode($bodyAsArray);

        $this->createRequest($community_id, $body);

        return true;
    }

    function afterWriteContent($path, \Sabre\DAV\IFile $node) {
        $this->sendAsync($this->request);

        return true;
    }

    private function sendAsync($request) {
      $this->httpClient->sendAsync(
          $request,
          function (ResponseInterface $response) {
            error_log('success');
            error_log(print_r($response->getBodyAsString(), true));
          },
          function($error) {
            error_log('error');
            error_log(print_r($error, true));
          }
      );
    }

    private function createRequest($community_id, $body) {
      $url = $this->apiroot.'/calendars/'.$community_id.'/events';
      $this->request = new HTTP\Request('POST', $url);
      $this->request->setHeader('Cookie', $this->connect_cookie);
      $this->request->setHeader('Content-type', 'application/json');
      $this->request->setHeader('Content-length', strlen($body));
      $this->request->setBody($body);
    }

    private function getCommunityIdFrom($principaluri) {
      $array = explode('/', $principaluri);
      $community_id = array_pop($array);
      return $community_id;
    }
}
