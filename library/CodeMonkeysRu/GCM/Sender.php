<?php

namespace CodeMonkeysRu\GCM;

/**
 * Messages sender to GCM servers
 *
 * @author Vladimir Savenkov <ivariable@gmail.com>
 * @modified Seshachalam Malisetti <abbiya@gmail.com>
 */
class Sender
{

    /**
     * GCM endpoint
     *
     * @var string
     */
    private $gcmUrl = 'https://android.googleapis.com/gcm/send';

    /**
     * An API key that gives the application server authorized access to Google services.
     *
     * @var string
     */
    private $serverApiKey = false;

    public function __construct($serverApiKey, $gcmUrl = false)
    {
        $this->serverApiKey = $serverApiKey;
        if ($gcmUrl) {
            $this->gcmUrl = $gcmUrl;
        }
    }

    /**
     * Send message to GCM without explicitly created message
     *
     * @param mixed same params as in Message bulkSet
     *
     * @throws \UnexpectedValueException
     * @return \CodeMonkeysRu\GCM\Response
     */
    public function sendMessage()
    {
        $message = new \CodeMonkeysRu\GCM\Message();
        call_user_func_array(array($message, 'bulkSet'), func_get_args());
        return $this->send($message);
    }

    /**
     * Send message to GCM
     *
     * @param \CodeMonkeysRu\GCM\Message $message
     * @throws \UnexpectedValueException
     * @return \CodeMonkeysRu\GCM\Response
     */
    public function send(Message $message)
    {

        if (!$this->serverApiKey) {
            throw new Exception("Server API Key not set", Exception::ILLEGAL_API_KEY);
        }

        $rawDataMessages = $this->formMessageData($message);
        
        $ch = array();
        $mh = curl_multi_init();

        $i = 0;
        foreach ($rawDataMessages as $rawData) {
            if (isset($rawData['data'])) {
                if (strlen(json_encode($rawData['data'])) > 4096) {
                    throw new Exception("Data payload is to big (max 4096 bytes)", Exception::MALFORMED_REQUEST);
                }
            }

            $data = json_encode($rawData);
            $headers = array(
                'Authorization: key=' . $this->serverApiKey,
                'Content-Type: application/json'
            );

            $ch[$i] = curl_init();

            curl_setopt($ch, CURLOPT_URL, $this->gcmUrl);

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_multi_add_handle($mh, $ch[$i]);
            $i++;
        }

        $active = null;
        //execute the handles
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }

        $responses = array();

        foreach ($ch as $handler) {
            $resultBody = curl_multi_getcontent($handler);
            array_push($responses, $resultBody);
            $resultHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            switch ($resultHttpCode) {
                case "200":
                    //All fine. Continue response processing.
                    break;

                case "400":
                    throw new Exception('Malformed request. ' . $resultBody, Exception::MALFORMED_REQUEST);
                    break;

                case "401":
                    throw new Exception('Authentication Error. ' . $resultBody, Exception::AUTHENTICATION_ERROR);
                    break;

                default:
                    //TODO: Retry-after
                    throw new Exception("Unknown error. " . $resultBody, Exception::UNKNOWN_ERROR);
                    break;
            }
            //close the handles
            curl_multi_remove_handle($mh, $handler);
        }
        curl_multi_close($mh);

        //make the final response
        $responseToSend = array();
        $canonicalIds = array();
        foreach ($responses as $response) {
            $data = \json_decode($response, true);
            if ($data === null) {
                throw new Exception("Malformed reponse body. " . $response, Exception::MALFORMED_RESPONSE);
            }
            $responseToSend['multicast_id'] = $data['multicast_id'];
            $responseToSend['failure'] = $data['failure'];
            $responseToSend['success'] = $data['success'];
            $canonicalIds = array_merge($canonicalIds, $data['canonical_ids']);
        }
        $responseToSend['canonical_ids'] = $canonicalIds;

        return new Response($message, json_encode($responseToSend));
    }

    /**
     * Form raw message data for sending to GCM
     *
     * @param \CodeMonkeysRu\GCM\Message $message
     * @return array
     */
    private function formMessageData(Message $message)
    {
        $messages = array();

        $regIdChunks = array_chunk($message->getRegistrationIds(), 1000);

        $dataFields = array(
            'registration_ids' => 'getRegistrationIds',
            'collapse_key' => 'getCollapseKey',
            'data' => 'getData',
            'delay_while_idle' => 'getDelayWhileIdle',
            'time_to_live' => 'getTtl',
            'restricted_package_name' => 'getRestrictedPackageName',
            'dry_run' => 'getDryRun',
        );

        foreach ($regIdChunks as $chunk) {
            $data = array(
                'registration_ids' => $chunk,
            );

            foreach ($dataFields as $fieldName => $getter) {
                if ($message->$getter() != null) {
                    $data[$fieldName] = $message->$getter();
                }
            }
            array_push($messages, $data);
        }

        return $messages;
    }

}
