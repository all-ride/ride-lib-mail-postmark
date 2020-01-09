<?php

namespace ride\library\mail\transport;

use Postmark\Models\PostmarkAttachment;
use Postmark\Models\PostmarkException;
use Postmark\PostmarkClient;

use ride\library\log\Log;
use ride\library\mail\exception\MailException;
use ride\library\mail\MailMessage;

use \Exception;

/**
 * Postmark message transport
 * @see http://postmarkapp.com
 */
class PostmarkTransport extends AbstractTransport {

    /**
     * Instance of the Postgun client
     * @var \Postmark\PostmarkClient
     */
    protected $postmark;

    /**
     * Errors of the last send
     * @var array
     */
    protected $errors;

    /**
     * Constructs a new message transport
     * @param string $apiKey Server token
     * @param \ride\library\log\Log|null $log
     * @param string|null $lineBreak
     */
    public function __construct($apiKey, Log $log = null, $lineBreak = null) {
        $this->postmark = new PostmarkClient($apiKey);

        parent::__construct($log, $lineBreak);
    }

    /**
     * Deliver a mail message to the Mandrill API
     * @param \ride\library\mail\MailMessage $message Message to send
     * @return null
     * @throws \ride\library\mail\exception\MailException when the message could
     * not be delivered
     */
    public function send(MailMessage $message) {
        try {
            // handle default values
            if (!$message->getFrom() && $this->defaultFrom) {
                $message->setFrom($this->defaultFrom);
            }

            if ($this->defaultBcc) {
                $message->addBcc($this->defaultBcc);
            }

            if (!$message->getReplyTo() && $this->defaultReplyTo) {
                $message->setReplyTo($this->defaultReplyTo);
            }

            // handle recipients
            $struct = array(
                'subject' => $message->getSubject(),
                'from' => $this->getAddress($message->getFrom()),
                'to' => $this->getAddresses($message->getTo()),
                'cc' => $this->getAddresses($message->getCc()),
                'bcc' => $this->getAddresses($message->getBcc()),
                'replyTo' => null,
                'headers' => array(),
                'attachments' => array(),
            );

            if ($message->getReplyTo()) {
                $struct['replyTo'] = $message->getReplyTo()->getEmailAddress();
            }

            // no proper return path support for postmark
            // if ($message->getReturnPath()) {
                // $struct['headers']['Return-Path'] = $message->getReturnPath()->getEmailAddress();
            // }

            // handle body
            if ($message->isHtmlMessage()) {
                $struct['html'] = $message->getMessage();
                $struct['text'] = $message->getPart(MailMessage::PART_ALTERNATIVE);
                if ($struct['text']) {
                    $struct['text'] = $struct['text']->getBody();
                }
            } else {
                $struct['html'] = null;
                $struct['text'] = $message->getMessage();
            }

            // add attachments
            $parts = $message->getParts();
            foreach ($parts as $name => $part) {
                if ($name == MailMessage::PART_BODY || $name == MailMessage::PART_ALTERNATIVE) {
                    continue;
                }

                $struct['attachments'][] = PostmarkAttachment::fromBase64EncodedData($part->getBody(), $name, $part->getMimeType());
            }

            // handle debug mode
            if ($this->debugTo) {
                $to = $struct['to'];
                if ($to) {
                    $to = explode(',', $to);
                }

                $cc = $struct['cc'];
                if ($cc) {
                    $cc = explode(',', $cc);
                } else {
                    $cc = array();
                }

                $bcc = $struct['bcc'];
                if ($bcc) {
                    $bcc = explode(',', $bcc);
                } else {
                    $bcc = array();
                }

                $struct['to'] = $this->debugTo;
                $struct['cc'] = null;
                $struct['bcc'] = null;

                if (isset($struct['html'])) {
                    $html = '<div style="padding: 15px; margin: 25px 50px; border: 1px solid red; color: red; background-color: #FFC">';
                    $html .= 'This mail is sent in debug mode. The original recipients are: <ul>';
                    foreach ($to as $recipient) {
                        $html .= '<li>' . $recipient . '</li>';
                    }
                    foreach ($cc as $recipient) {
                        $html .= '<li>' . $recipient .  ' (CC)</li>';
                    }
                    foreach ($bcc as $recipient) {
                        $html .= '<li>' . $recipient .  ' (BCC)</li>';
                    }
                    $html .= '</div>';

                    $struct['html'] = $html . $struct['html'];
                }

                $text = "\n\nThis mail is sent in debug mode. The original recipients are: \n";
                foreach ($to as $recipient) {
                    $text .= '- ' . $recipient . "\n";
                }
                foreach ($cc as $recipient) {
                    $text .= '- ' . $recipient . " (CC)\n";
                }
                foreach ($bcc as $recipient) {
                    $text .= '- ' . $recipient . " (BCC)\n";
                }
                $text .= "\n\n";

                $struct['text'] = $text . $struct['text'];
            }

            // send the mail
            try {
                $this->errors = array();

                $result = $this->postmark->sendEmail(
                    $struct['from'],
                    $struct['to'],
                    $struct['subject'],
                    $struct['html'],
                    $struct['text'],
                    null,
                    true,
                    $struct['replyTo'],
                    $struct['cc'],
                    $struct['bcc'],
                    $struct['headers'],
                    $struct['attachments'],
                    'HtmlAndText',
                    null
                );


                if ($result['errorCode'] !== 0) {
                    $this->errors[] = $result['message'];
                }
            } catch (PostmarkException $exception) {
                $this->errors[] = $exception->httpStatusCode . ': ' . $exception->message . ' (' . $exception->postmarkApiErrorCode . ')';
            }

            if ($this->errors) {
                $errorDescription = '';
                foreach ($this->errors as $error) {
                    $errorDescription .= $error;
                }
            }

            // log the mail
            if (isset($struct['text'])) {
                unset($struct['text']);
            }
            if (isset($struct['body'])) {
                unset($struct['body']);
            }
            if (isset($struct['attachments'])) {
                unset($struct['attachments']);
            }

            $this->logMail($struct['subject'], var_export($struct, true), count($this->errors));

            if (count($this->errors)) {
                throw new MailException('Errors returned from the server: ' . $errorDescription);
            }
        } catch (Exception $exception) {
            throw new MailException('Could not send the mail', 0, $exception);
        }
    }

    /**
     * Gets the errors of the last send action
     * @return array Email address of the recipient as key, error as value
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Gets the addresses in Mandrill format
     * @param MailAddress|array $addresses Array with MailAddress instances
     * @return array Array of address structs
     */
    protected function getAddresses($addresses) {
        if (!$addresses) {
            return null;
        }

        if (!is_array($addresses)) {
            $addresses = array($addresses);
        }

        $result = array();

        foreach ($addresses as $address) {
            $result[] = $address->getEmailAddress();
        }

        return implode(',', $result);
    }

    /**
     * Gets an address struct
     * @param \ride\library\mail\MailAddress $address
     * @param string $type
     * @return array Address struct
     */
    protected function getAddress($address) {
        if ($address === null) {
            return null;
        }

        return $address->getEmailAddress();
    }

}
