<?php

namespace app\models;
use app\libraries\Core;
use app\libraries\Utils;

/**
 * Class Email
 *
 * @method void     setSubject($sub)
 * @method void     setBody($bod)
 * @method void     setUserId($uid)
 * @method void     setRecipient($recip)

 * @method string   getSubject()
 * @method string   getBody()
 * @method string   getUserId()
 * @method string   getRecipient()
 */
class Email extends AbstractModel {
    /** @property @var string Subject line of email */
    protected $subject;
    /** @property @var string Body of email */
    protected $body;
    /** @property @var string user name */
    protected $user_id;

    /** @property @var string Intended receiver of email */
    // NOTE: THIS IS ESSENTIALLY A DEPRECATED / LEGACY FIELD
    protected $recipient;


  /**
   * Email constructor.
   *
   * @param Core  $core
   * @param array $details
   */

    public function __construct(Core $core, $details=array()) {
        parent::__construct($core);
        if (count($details) == 0) {
            return;
        }
        $this->setUserId($details["to_user_id"]);
        $this->setRecipient('DEPRECATED');
        $this->setSubject($this->formatSubject($details["subject"]));
        $this->setBody($this->formatBody($details["body"]));
    }

    //inject course label into subject
    private function formatSubject($subject) {
        $course = $this->core->getConfig()->getCourse();
        return "[Submitty $course]: ".$subject;
    }

    //inject a "do not reply" note in the footer of the body
    private function formatBody($body){
        return $body."\n--\nNOTE: This is an automated email.\nAny responses will not be looked at or responded to.";
    }

}
