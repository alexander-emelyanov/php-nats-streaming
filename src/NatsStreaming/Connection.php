<?php


namespace NatsStreaming;

use DateTime;
use Nats\Message;
use Nats\Php71RandomGenerator;
use NatsStreaming\Contracts\ConnectionContract;
use pb\Ack;
use pb\CloseRequest;
use pb\CloseResponse;
use pb\ConnectRequest;
use pb\ConnectResponse;
use pb\MsgProto;
use pb\PubMsg;
use pb\StartPosition;
use pb\SubscriptionRequest;
use pb\SubscriptionResponse;
use RandomLib\Factory;

class Connection implements ConnectionContract
{


    const UID_LENGTH = 16;
    const DEFAULT_ACK_PREFIX = '_STAN.acks';
    const DEFAULT_DISCOVER_PREFIX = '_STAN.discover';
    /**
     * @var ConnectionOptions
     */
    public $options;


    /**
     * @var \RandomLib\Generator
     */
    private $randomGenerator;

    private $pubPrefix;

    //private $subPrefix;

    private $subRequests;

    private $unsubRequests;

    private $closeRequests;

    //private $ackSubject;


//    private $ackSubscription;
//    private $hbSubscription;

    private $subMap = [];

    //private $pubAckMap = [];

    private $connected;

    private $reconnects = 0;
    private $pubs = 0;


    /**
     * @var \Nats\Connection
     */
    public $natsCon;

    private $subCloseRequests;

    private $timeout;

    public function __construct(ConnectionOptions $options = null)
    {

        if ($options === null) {
            $options = new ConnectionOptions();
        }

        $this->options  = $options;

        if (version_compare(phpversion(), '7.0', '>') === true) {
            $this->randomGenerator = new Php71RandomGenerator();
        } else {
            $randomFactory         = new Factory();
            $this->randomGenerator = $randomFactory->getLowStrengthGenerator();
        }

        $this->natsCon = new \Nats\Connection($this->options->getNatsOptions());
    }

    private function msgIsEmpty($data) {
        return $data === "\r\n";
    }

    /**
     * @param null $timeout
     * @throws Exception
     */
    public function connect($timeout = null)
    {

        $this->natsCon->connect($timeout);

        $this->timeout      = $timeout;
        $hbInbox = $this->randomGenerator->generateString(self::UID_LENGTH);

        //$this->natsCon->subscribe($hbInbox, function($message) { $this->processHeartbeat($message);});

        $discoverPrefix = $this->options->getDiscoverPrefix() ? $this->options->getDiscoverPrefix() : self::DEFAULT_DISCOVER_PREFIX;

        $discoverSubject = $discoverPrefix . '.' . $this->options->getClusterID();

        $req = new ConnectRequest();
        $req->setClientID($this->options->getClientID());
        $req->setHeartbeatInbox($hbInbox);

        $data = $req->toStream()->getContents();
        /**
         * @var $resp ConnectResponse
         */
        $resp = null;

        $this->natsCon->request($discoverSubject, $data, function ($message) use (&$resp) {
            $resp = ConnectResponse::fromStream($message->getBody());
        });


        if ($resp->getError()) {
            $this->close();
            throw Exception::forFailedConnection($resp->getError());
        }


        $this->pubPrefix = $resp->getPubPrefix();
        $this->subRequests = $resp->getSubRequests();
        $this->unsubRequests = $resp->getUnsubRequests();
        $this->subCloseRequests = $resp->getSubCloseRequests();
        $this->closeRequests = $resp->getCloseRequests();


        $this->connected = true;
        // what to do about this
        //$this->natsCon->subscribe($this->ackSubject, function($message) { $this->processAck($message);});
    }


    public function publish($subject, $data)
    {

        $subj = $this->pubPrefix . '.' . $subject;
        $peGUID = $this->randomGenerator->generateString(self::UID_LENGTH);

        $req = new PubMsg();
        $req->setClientID($this->options->getClientID());
        $req->setGuid($peGUID);
        $req->setSubject($subject);
        $req->setData($data);

        $bytes = $req->toStream()->getContents();

        $ackSubject = self::DEFAULT_ACK_PREFIX . '.' . $this->randomGenerator->generateString(self::UID_LENGTH);

        $sid   = $this->natsCon->subscribe(
            $ackSubject,
            function ($message) {
                /**
                 * @var $message Message
                 */

                //$resp = Ack::fromStream($message->getBody());
            }
        );
        $this->natsCon->unsubscribe($sid, 1);
        $this->natsCon->publish($subj, $bytes, $ackSubject);
        $this->natsCon->wait(1);
        $this->pubs += 1;
    }

//    public function publishAsync($subject, $data, callable $ackHander)
//    {
//        // TODO: Implement publishAsync() method.
//    }

    public function subscribe($subjects, callable $cb, $subscriptionOptions)
    {
        /*
         *
         * sub := &subscription{subject: subject, qgroup: qgroup, inbox: nats.NewInbox(), cb: cb, sc: sc, opts: DefaultSubscriptionOptions}
         */


    }
//
//    public function queueSubscribe($subjects, $qGroup, callable $cb, $subscriptionOptions)
//    {
//        // TODO: Implement queueSubscribe() method.
//    }


    /**
     * @param $subject
     * @param $qGroup
     * @param callable $cb
     * @param SubscriptionOptions $subscriptionOptions
     * @throws Exception
     * @throws \Exception
     */
    private function _subscribe($subject, $qGroup, callable $cb, $subscriptionOptions)
    {
        $inbox = uniqid('_INBOX.');
        $sub = new Subscription([
            'subject' => $subject,
            'qGroup' => $qGroup,
            'inbox' => $inbox,
            'opts' => $subscriptionOptions,
            'cb' => $cb
        ]);
        $this->subMap[$sub->getInbox()] = $sub;
        $sid = $this->natsCon->subscribe($sub->getInbox(), function($message){$this->processMsg($message);});

        $sub->setSid($sid);
        $this->subMap[$sub->getInbox()] = $sub;


        $req = new SubscriptionRequest();
        $req->setSubject($sub->getSubject());
        $req->setClientID($this->options->getClientID());
        $req->setAckWaitInSecs($subscriptionOptions->getAckWaitSecs());
        $req->setDurableName($subscriptionOptions->getDurableName());
        $req->setInbox($sub->getInbox());
        $req->setStartPosition($subscriptionOptions->getStartAt());

        switch ($req->getStartPosition()->value()) {
            case StartPosition::SequenceStart_VALUE:
                $req->setStartSequence($subscriptionOptions->getStartSequence());
                break;
            case StartPosition::TimeDeltaStart_VALUE:
                $nowNano = microtime() * 1000;
                $req->setStartTimeDelta( $nowNano - $subscriptionOptions->getStartMicroTime() * 1000);
                break;
        }

        $data = $req->toStream()->getContents();
        /**
         * @var $resp SubscriptionResponse
         */
        $resp = null;
        try {

            $this->natsCon->request($this->subRequests, $data, function($message) use (&$resp) {
                $resp = SubscriptionResponse::fromStream($message->getBody());
            });
        } catch (\Exception $e) {
            $this->natsCon->unsubscribe($sid);
            throw $e;
        }

        if (!$resp) {
            $this->natsCon->unsubscribe($sid);
            throw Exception::forTimeout('no response for subscribe request');
        }

        if ($resp->getError()) {
            $this->natsCon->unsubscribe($sid);
            throw Exception::forFailedSubscription($resp->getError());
        }


        $sub->setAckInbox($resp->getAckInbox());



    }

    /**
     * @param $rawMessage Message
     */
    private function processMsg($rawMessage){

        $message = MsgProto::fromStream($rawMessage->getBody());


        /**
         * @var $sub Subscription
         */
        $sub = $this->subMap[$message->getSubject()];


        if ($sub == null) {
            return;
        }

        $ackSubject = $sub->getAckInbox();

        // smuggle ack subject
        $message->ackSubject = $ackSubject;

        $isManualAck = $sub->getOpts()->isManualAck();
        $cb = $sub->getCb();
        if ($cb != null) {
            $cb($message);
        }

        if (!$isManualAck) {
            $this->ack($message);
        }
    }

    public function reconnectsCount()
    {
        return $this->reconnects;
    }

    public function pubsCount()
    {
        return $this->pubs;
    }


    /**
     * @param $message Message
     */
    private function processHeartbeat($message)
    {
        $message->reply(null);
    }



    /**
     * Reconnects to the server.
     *
     * @return void
     */
    public function reconnect()
    {
        $this->reconnects += 1;
        $this->close();
        $this->connect($this->timeout);
    }

    public function isConnected()
    {

        if (!$this->natsCon->isConnected()) {
            $this->connected = false;
        }

        return $this->connected;
    }

    public function close()
    {

        if (!$this->closeRequests || !$this->options->getClientID() || !$this->connected) {
            $this->connected = false;
            return;
        }
        $this->connected = false;

        $req = new CloseRequest();
        $req->setClientID($this->options->getClientID());
        /**
         * @var $resp CloseResponse
         */
        $resp = null;
        $reqBody  = $req->toStream()->getContents();
        $this->natsCon->request($this->closeRequests, $reqBody, function ($message) use (&$resp) {
            /**
             * @var $message Message
             */

            if (!$this->msgIsEmpty($message->getBody())) {
                $resp = CloseResponse::fromStream($message->getBody());
            }
        });

        if ($resp && $resp->getError()) {
            throw Exception::forFailedDisconnection($resp->getError());
        }

        $this->natsCon->close();
    }

    public function natsConn()
    {
        return $this->natsCon;
    }

    public function __destruct()
    {
        if ($this->isConnected()) {
            $this->close();
        }
    }

    /**
     * @param MsgProto $message
     * @return void
     */
    public function ack($message)
    {
        $req = new Ack();
        $req->setSubject($message->getSubject());
        $req->setSequence($message->getSequence());
        $data = $req->toStream()->getContents();
        $this->natsCon->publish($message->ackSubject, $data);
    }
}