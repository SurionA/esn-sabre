<?php
namespace ESN\CalDAV\Schedule;

// @codeCoverageIgnoreStart
use
    Sabre\HTTP\RequestInterface,
    Sabre\HTTP\ResponseInterface,
    Sabre\VObject\Component\VCalendar,
    Sabre\VObject\ITip,
    Sabre\CalDAV\ICalendarObject,
    Sabre\CalDAV\ISchedulingObject;

// @codeCoverageIgnoreEnd

/**
 * This is a hack for making email invitations work. SabreDAV doesn't find a
 * valid attendee or organizer because the group calendar doesn't have the
 * right owner. Using the currently authenticated user is not technically
 * correct, because in case of delegated access it will be the wrong user, but
 * for the ESN we assume that the user accessing is also the user being
 * processed.
 *
 * Most of this code is copied from SabreDAV, therefore we opt to not cover it
 * @codeCoverageIgnore
 */
class Plugin extends \Sabre\CalDAV\Schedule\Plugin {

    private function scheduleReply(RequestInterface $request) {

        $scheduleReply = $request->getHeader('Schedule-Reply');
        return $scheduleReply!=='F';

    }

    /**
     * Used to perform healthchecks on the Message before delivery.
     *
     * @param ITip\Message $iTipMessage The Message to deliver.
     */
    function deliver(ITip\Message $iTipMessage)
    {
        if (!$iTipMessage->message->VEVENT->SEQUENCE) {
            $iTipMessage->message->VEVENT->SEQUENCE = 0;
        }

        parent::deliver($iTipMessage);
    }

    function calendarObjectChange(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        // ITIP operations are silent -> no email should be sent
        if ($request->getMethod() === 'ITIP' || !$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        $calendarNode = $this->server->tree->getNodeForPath($calendarPath);

        $authPlugin = $this->server->getPlugin('auth');
        $principal = !is_null($authPlugin) ? $authPlugin->getCurrentPrincipal() : $calendarNode->getOwner();
        $addresses = $this->getAddressesForPrincipal($principal);

        if (!$isNew) {
            $node = $this->server->tree->getNodeForPath($request->getPath());
            $oldObj = \Sabre\VObject\Reader::read($node->get());
        } else {
            $oldObj = null;
        }

        $this->processICalendarChange($oldObj, $vCal, $addresses, [], $modified);
    }

    function beforeUnbind($path) {

        // FIXME: We shouldn't trigger this functionality when we're issuing a
        // MOVE. This is a hack.
        if ($this->server->httpRequest->getMethod()==='MOVE') return;

        $node = $this->server->tree->getNodeForPath($path);

        if (!$node instanceof ICalendarObject || $node instanceof ISchedulingObject) {
            return;
        }

        if (!$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        $authPlugin = $this->server->getPlugin('auth');
        $principal = !is_null($authPlugin) ? $authPlugin->getCurrentPrincipal() : $calendarNode->getOwner();
        $addresses = $this->getAddressesForPrincipal($principal);

        $broker = new ITip\Broker();
        $messages = $broker->parseEvent(null, $addresses, $node->get());

        foreach($messages as $message) {
            $this->deliver($message);
        }
    }
}
